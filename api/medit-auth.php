<?php
/**
 * Medit Link Connection API (admin)
 *
 * Practice-admin endpoints that drive the Medit Link OAuth2 connection
 * flow. The authorization itself happens on Medit's site; this endpoint
 * only mints tracked attempts and reports/manages the resulting
 * connection.
 *
 *   GET  ?action=status                    -> {state, connected, environment}
 *   POST {action:'begin'}                  -> {authorize_url, attempt_id}
 *   POST {action:'disconnect'}             -> local wipe (no remote
 *                                             revocation is documented)
 *
 * SECURITY MODEL (every action):
 *   authenticated session -> valid practice context -> practice admin ->
 *   not a lab collaborator -> CSRF (POST) -> MEDIT_CONNECT flag ->
 *   MEDIT_ALLOWED_PRACTICES allowlist -> all state derived from the
 *   server-side session (never request params).
 *
 * The browser redirect to Medit happens client-side from authorize_url;
 * Medit returns the browser to api/medit-callback.php. Secrets never
 * appear here: the Client Secret lives only in server environment config
 * (MeditConfig); tokens persist via IntegrationCredentials (encrypted).
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/integrations/MeditConfig.php';
require_once __DIR__ . '/integrations/medit/MeditAuthService.php';
require_once __DIR__ . '/integrations/IntegrationManager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
setApiSecurityHeaders();

set_exception_handler(function (Throwable $e) {
    error_log('[medit-auth] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'The Medit Link connection could not be completed.']);
    exit;
});

function meditAuthFail(int $status, string $message, ?string $errorCode = null): void {
    http_response_code($status);
    $out = ['success' => false, 'message' => $message];
    if ($errorCode !== null) {
        $out['error_code'] = $errorCode;
    }
    echo json_encode($out);
    exit;
}

// Feature gate first - the connection flow is entirely off unless the
// MEDIT_CONNECT flag is enabled. Server-side enforcement, not hidden UI.
if (!isFeatureEnabled('MEDIT_CONNECT')) {
    meditAuthFail(404, 'Not found', 'feature_disabled');
}

if (!isset($_SESSION['db_user_id'])) {
    meditAuthFail(401, 'Authentication required', 'not_authenticated');
}
$userId = (int)$_SESSION['db_user_id'];

$currentPracticeId = requireValidPracticeContext();
requirePracticeAdmin($currentPracticeId);
requireNotLabCollaborator($currentPracticeId, 'Access denied.');
requireCurrentTermsAcceptedForApi($userId);

// Practice allowlist applies to every action on this endpoint - an
// unallowlisted practice cannot read status, begin, or disconnect.
if (!MeditConfig::isPracticeAllowed((int)$currentPracticeId)) {
    meditAuthFail(403, 'Medit Link access is not enabled for this practice.', 'practice_not_allowed');
}

global $pdo;
if (!$pdo) {
    meditAuthFail(500, 'Service unavailable', 'db_unavailable');
}

$service = new MeditAuthService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// GET status: safe connection-state projection for the active practice
// -------------------------------------------------------------------------
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'status';
    if ($action !== 'status') {
        meditAuthFail(400, 'Unknown action', 'unknown_action');
    }

    $stmt = $pdo->prepare("
        SELECT * FROM integration_connections
        WHERE practice_id = :pid AND provider = :p LIMIT 1
    ");
    $stmt->execute([':pid' => (int)$currentPracticeId, ':p' => MeditAuthService::PROVIDER]);
    $connection = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // A connection authorized under a different environment must not
    // present as connected - it can only be repaired by a fresh OAuth
    // round-trip.
    $envMatch = MeditConfig::connectionMatchesEnvironment($connection);
    $displayConnection = $envMatch ? $connection : null;

    echo json_encode([
        'success'     => true,
        'state'       => MeditAuthService::connectionState($displayConnection),
        'environment_mismatch' => ($connection !== null && !$envMatch),
        'environment' => MeditConfig::environment(),
        'redirect_uri' => MeditAuthService::redirectUri(),
        'server_configured' => MeditConfig::isConfigured(),
        'practice_allowed'  => MeditConfig::isPracticeAllowed((int)$currentPracticeId),
        'connection'  => $connection ? IntegrationManager::toPublicConnection($pdo, $connection) : null,
    ]);
    exit;
}

if ($method !== 'POST') {
    meditAuthFail(405, 'Method not allowed');
}

requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    meditAuthFail(400, 'Invalid request', 'invalid_json');
}
$action = (string)($input['action'] ?? '');

// -------------------------------------------------------------------------
// POST begin: mint a fresh attempt, return the Medit authorize URL.
// Earlier unconsumed attempts simply expire.
// -------------------------------------------------------------------------
if ($action === 'begin') {
    try {
        $attempt = $service->beginAttempt($pdo, (int)$currentPracticeId, $userId);
    } catch (MeditAuthException $e) {
        $status = $e->category === 'server_config_missing' ? 503
            : ($e->category === 'practice_not_allowed' ? 403 : 500);
        meditAuthFail($status, 'The Medit Link connection could not be started.', $e->category);
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'medit_auth_started', "practice_id={$currentPracticeId}");
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
// POST disconnect: local token wipe + disable inside the refresh lock.
// The Medit guide documents NO revocation endpoint, so remote_revoked is
// always false - reported honestly rather than implied.
// Independent of the user's own DentaTrak session.
// -------------------------------------------------------------------------
if ($action === 'disconnect') {
    $stmt = $pdo->prepare("
        SELECT * FROM integration_connections
        WHERE practice_id = :pid AND provider = :p LIMIT 1
    ");
    $stmt->execute([':pid' => (int)$currentPracticeId, ':p' => MeditAuthService::PROVIDER]);
    $connection = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$connection) {
        meditAuthFail(404, 'No Medit Link connection exists for this practice.', 'not_found');
    }

    try {
        $result = $service->disconnect($pdo, (int)$connection['id']);
    } catch (MeditAuthException $e) {
        meditAuthFail(500, 'The Medit Link connection could not be disconnected.', $e->category);
    }

    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'medit_disconnected',
            "practice_id={$currentPracticeId} connection_id=" . (int)$connection['id']);
    }

    echo json_encode([
        'success'        => true,
        'state'          => 'disconnected',
        'remote_revoked' => (bool)($result['remote_revoked'] ?? false),
    ]);
    exit;
}

meditAuthFail(400, 'Unknown action', 'unknown_action');
