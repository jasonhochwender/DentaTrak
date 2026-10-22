<?php
/**
 * DS Core Connection API (admin)
 *
 * Practice-admin endpoints that drive the DS Core OAuth2 connection flow.
 * The authorization itself happens on DS Core's site; this endpoint only
 * mints tracked attempts and reports/manages the resulting connection.
 *
 *   GET  ?action=status                    -> {state, connected, environment}
 *   POST {action:'begin'}                  -> {authorize_url, attempt_id}
 *   POST {action:'disconnect'}             -> local wipe + remote revoke
 *
 * SECURITY MODEL (every action):
 *   authenticated session -> valid practice context -> practice admin ->
 *   not a lab collaborator -> CSRF (POST) -> DS_CORE_CONNECT flag ->
 *   all state derived from the server-side session (never request params).
 *
 * The browser redirect to DS Core happens client-side from authorize_url;
 * DS Core returns the browser to api/dscore-callback.php. Secrets never
 * appear here: the Client Secret lives only in server environment config
 * (DsCoreConfig); tokens persist via IntegrationCredentials (encrypted).
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/integrations/DsCoreConfig.php';
require_once __DIR__ . '/integrations/dscore/DsCoreAuthService.php';
require_once __DIR__ . '/integrations/IntegrationManager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
setApiSecurityHeaders();

set_exception_handler(function (Throwable $e) {
    error_log('[dscore-auth] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'The DS Core connection could not be completed.']);
    exit;
});

function dsCoreAuthFail(int $status, string $message, ?string $errorCode = null): void {
    http_response_code($status);
    $out = ['success' => false, 'message' => $message];
    if ($errorCode !== null) {
        $out['error_code'] = $errorCode;
    }
    echo json_encode($out);
    exit;
}

// Feature gate first - the connection flow is entirely off unless the
// DS_CORE_CONNECT flag is enabled. Server-side enforcement, not hidden UI.
if (!isFeatureEnabled('DS_CORE_CONNECT')) {
    dsCoreAuthFail(404, 'Not found', 'feature_disabled');
}

if (!isset($_SESSION['db_user_id'])) {
    dsCoreAuthFail(401, 'Authentication required', 'not_authenticated');
}
$userId = (int)$_SESSION['db_user_id'];

$currentPracticeId = requireValidPracticeContext();
requirePracticeAdmin($currentPracticeId);
requireNotLabCollaborator($currentPracticeId, 'Access denied.');
requireCurrentTermsAcceptedForApi($userId);

global $pdo;
if (!$pdo) {
    dsCoreAuthFail(500, 'Service unavailable', 'db_unavailable');
}

$service = new DsCoreAuthService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// GET status: safe connection-state projection for the active practice
// -------------------------------------------------------------------------
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'status';
    if ($action !== 'status') {
        dsCoreAuthFail(400, 'Unknown action', 'unknown_action');
    }

    $stmt = $pdo->prepare("
        SELECT * FROM integration_connections
        WHERE practice_id = :pid AND provider = :p LIMIT 1
    ");
    $stmt->execute([':pid' => (int)$currentPracticeId, ':p' => DsCoreAuthService::PROVIDER]);
    $connection = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode([
        'success'     => true,
        'state'       => DsCoreAuthService::connectionState($connection),
        'environment' => DsCoreConfig::environment(),
        'redirect_uri' => DsCoreAuthService::redirectUri(),
        'server_configured' => DsCoreConfig::isConfigured(),
        'connection'  => $connection ? IntegrationManager::toPublicConnection($pdo, $connection) : null,
    ]);
    exit;
}

if ($method !== 'POST') {
    dsCoreAuthFail(405, 'Method not allowed');
}

requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    dsCoreAuthFail(400, 'Invalid request', 'invalid_json');
}
$action = (string)($input['action'] ?? '');

// -------------------------------------------------------------------------
// POST begin: mint a fresh PKCE attempt, return the DS Core authorize URL.
// Each call produces a fresh verifier; earlier unconsumed attempts simply
// expire (their verifier never leaks and the callback is single-use).
// -------------------------------------------------------------------------
if ($action === 'begin') {
    try {
        $attempt = $service->beginAttempt($pdo, (int)$currentPracticeId, $userId);
    } catch (DsCoreAuthException $e) {
        dsCoreAuthFail($e->category === 'server_config_missing' ? 503 : 500,
            'The DS Core connection could not be started.', $e->category);
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'dscore_auth_started', "practice_id={$currentPracticeId}");
    }

    echo json_encode([
        'success'       => true,
        'authorize_url' => $attempt['authorize_url'],
        'attempt_id'    => $attempt['attempt_id'],
        'expires_at'    => $attempt['expires_at'],
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST disconnect: local token wipe + disable, then a best-effort remote
// revocation (documented token:revoke, form-urlencoded). remote_revoked is
// reported honestly - false when revocation was not confirmed upstream.
// Independent of the user's own DentaTrak session.
// -------------------------------------------------------------------------
if ($action === 'disconnect') {
    $stmt = $pdo->prepare("
        SELECT * FROM integration_connections
        WHERE practice_id = :pid AND provider = :p LIMIT 1
    ");
    $stmt->execute([':pid' => (int)$currentPracticeId, ':p' => DsCoreAuthService::PROVIDER]);
    $connection = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$connection) {
        dsCoreAuthFail(404, 'No DS Core connection exists for this practice.', 'not_found');
    }

    try {
        $result = $service->disconnect($pdo, (int)$connection['id']);
    } catch (DsCoreAuthException $e) {
        dsCoreAuthFail(500, 'The DS Core connection could not be disconnected.', $e->category);
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'dscore_disconnected',
            "practice_id={$currentPracticeId} connection_id=" . (int)$connection['id']
            . " remote_revoked=" . (($result['remote_revoked'] ?? false) ? '1' : '0'));
    }

    echo json_encode([
        'success'        => true,
        'state'          => 'disconnected',
        'remote_revoked' => (bool)($result['remote_revoked'] ?? false),
    ]);
    exit;
}

dsCoreAuthFail(400, 'Unknown action', 'unknown_action');
