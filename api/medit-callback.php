<?php
/**
 * Medit Link OAuth Callback
 *
 * Registered redirect URI for the Medit Link authorization flow:
 *   https://dentatrak.com/api/medit-callback.php   (production)
 * The exact value must be registered in the Medit developer portal;
 * changes require the app registration to be updated there.
 *
 * Medit redirects the user's browser here with:
 *   ?code={authorization code}&state={attempt id}
 * or ?error=... when the user declines the consent screen.
 *
 * Security: the `state` value binds this callback to a server-side
 * attempt row (medit_oauth_attempts) created by a practice admin via
 * api/medit-auth.php. The attempt is single-use and expiring, and the
 * live session must still belong to the same user in the same practice -
 * otherwise the callback cannot complete. `code` and `state` are never
 * logged.
 *
 * Flag enforcement: if MEDIT_CONNECT is switched off while an
 * authorization is in flight, completion is rejected ('disabled') before
 * the code is exchanged or any connection is activated. The attempt
 * itself is left unconsumed so it simply expires.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/integrations/MeditConfig.php';
require_once __DIR__ . '/integrations/medit/MeditAuthService.php';

set_exception_handler(function (Throwable $e) {
    error_log('[medit-callback] ' . $e->getMessage());
    header('Location: ../main.php?medit=error&reason=internal');
    exit;
});

function meditRedirect(string $outcome, string $reason = ''): void {
    $qs = 'medit=' . $outcome;
    if ($reason !== '') {
        $qs .= '&reason=' . rawurlencode($reason);
    }
    header('Location: ../main.php?' . $qs);
    exit;
}

// The callback only completes inside the initiating admin's own session.
if (!isset($_SESSION['db_user_id']) || !isset($_SESSION['current_practice_id'])) {
    meditRedirect('error', 'session');
}

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    meditRedirect('error', 'internal');
}

$service = new MeditAuthService();
$result = $service->handleCallback(
    $pdo,
    $_GET,
    (int)$_SESSION['db_user_id'],
    (int)$_SESSION['current_practice_id']
);

switch ($result['result']) {
    case 'connected':
        meditRedirect('connected');
    case 'cancelled':
        meditRedirect('cancelled');
    case 'expired':
        meditRedirect('error', 'expired');
    case 'replay':
        meditRedirect('error', 'replayed');
    case 'forbidden':
        meditRedirect('error', 'forbidden');
    case 'disabled':
        meditRedirect('error', 'disabled');
    case 'exchange_failed':
        meditRedirect('error', 'exchange');
    default:
        meditRedirect('error', 'invalid');
}
