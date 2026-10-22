<?php
/**
 * DS Core OAuth Callback
 *
 * Registered redirect URI for the DS Core authorization flow:
 *   https://dentatrak.com/api/dscore-callback.php   (production)
 * The exact value must be registered in the DS Core developer portal;
 * changes require a new Client ID, so this route is permanent and stable.
 *
 * DS Core redirects the user's browser here with:
 *   ?code={authorization code}&iss={issuer}&state={attempt id}&client_id={id}
 * or ?error=... when the user declines the consent screen.
 *
 * Security: the `state` value binds this callback to a server-side attempt
 * row (dscore_oauth_attempts) created by a practice admin via
 * api/dscore-auth.php. The attempt is single-use and expiring, the PKCE
 * verifier never leaves the server, and the live session must still belong
 * to the same user in the same practice - otherwise the callback cannot
 * complete. The `code` and `state` values are never logged.
 *
 * This endpoint intentionally does NOT check the DS_CORE_CONNECT flag:
 * an attempt that was legitimately started should complete (or fail)
 * through this route even if the flag is toggled mid-flow.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/integrations/DsCoreConfig.php';
require_once __DIR__ . '/integrations/dscore/DsCoreAuthService.php';

set_exception_handler(function (Throwable $e) {
    error_log('[dscore-callback] ' . $e->getMessage());
    header('Location: ../main.php?dscore=error&reason=internal');
    exit;
});

function dsCoreRedirect(string $outcome, string $reason = ''): void {
    $qs = 'dscore=' . $outcome;
    if ($reason !== '') {
        $qs .= '&reason=' . rawurlencode($reason);
    }
    header('Location: ../main.php?' . $qs);
    exit;
}

// The callback only completes inside the initiating admin's own session.
if (!isset($_SESSION['db_user_id']) || !isset($_SESSION['current_practice_id'])) {
    dsCoreRedirect('error', 'session');
}

// Sanity check: when DS Core echoes client_id, it must match the app it
// was issued for - a callback for a different registered app is invalid.
$returnedClient = isset($_GET['client_id']) ? trim((string)$_GET['client_id']) : '';
$expectedClient = DsCoreConfig::clientId();
if ($returnedClient !== '' && $expectedClient !== null && $returnedClient !== $expectedClient) {
    dsCoreRedirect('error', 'invalid');
}

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    dsCoreRedirect('error', 'internal');
}

$service = new DsCoreAuthService();
$result = $service->handleCallback(
    $pdo,
    $_GET,
    (int)$_SESSION['db_user_id'],
    (int)$_SESSION['current_practice_id']
);

switch ($result['result']) {
    case 'connected':
        dsCoreRedirect('connected');
    case 'cancelled':
        dsCoreRedirect('cancelled');
    case 'expired':
        dsCoreRedirect('error', 'expired');
    case 'replay':
        dsCoreRedirect('error', 'replayed');
    case 'forbidden':
        dsCoreRedirect('error', 'forbidden');
    case 'exchange_failed':
        dsCoreRedirect('error', 'exchange');
    default:
        dsCoreRedirect('error', 'invalid');
}
