<?php
/**
 * Scanner Simulator Trigger (development/test only)
 *
 * Explicitly invokes the simulated scanner connector for the CURRENT
 * session practice, pushing a chosen synthetic scenario through the real
 * ingestion -> storage -> inbox pipeline.
 *
 * HARD GUARDS (all server-side, all fail closed):
 *   1. SimulatedScannerConnector::isAvailable() - non-production env AND
 *      the SCANNER_SIMULATOR flag. In production this is unconditionally
 *      false, so this endpoint can never run there.
 *   2. Authenticated session + valid practice context + practice admin.
 *   3. CSRF token required (POST only).
 *
 * The simulation writes ONLY scanner_* rows and scan storage objects. It
 * performs no emails, no notifications, no Open Dental or external writes.
 *
 *   POST {scenario: 'success'|'repeat'|'missing_patient'|'partial'|
 *                   'retryable'|'revised'|'empty'}
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/integrations/scanners/SimulatedScannerConnector.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
setApiSecurityHeaders();

set_exception_handler(function (Throwable $e) {
    error_log('[scanner-simulate] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Simulation unavailable.']);
    exit;
});

function simFail(int $status, string $message, ?string $errorCode = null): void {
    http_response_code($status);
    $out = ['success' => false, 'message' => $message];
    if ($errorCode !== null) {
        $out['error_code'] = $errorCode;
    }
    echo json_encode($out);
    exit;
}

// Guard 1: environment + flag. Checked before ANY auth work so the
// endpoint is indistinguishable from missing in production.
if (!SimulatedScannerConnector::isAvailable()) {
    simFail(404, 'Not found', 'unavailable');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    simFail(405, 'Method not allowed');
}

// Guard 2: session + practice + admin (mirrors integrations.php stack).
if (!isset($_SESSION['db_user_id'])) {
    simFail(401, 'Authentication required', 'not_authenticated');
}
$userId = (int)$_SESSION['db_user_id'];
$currentPracticeId = requireValidPracticeContext();
requirePracticeAdmin($currentPracticeId);
requireNotLabCollaborator($currentPracticeId, 'Access denied.');

// Guard 3: CSRF.
requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    simFail(400, 'Invalid request', 'invalid_json');
}

$scenario = (string)($input['scenario'] ?? 'success');
if (!in_array($scenario, SimulatedScannerConnector::SCENARIOS, true)) {
    simFail(400, 'Unknown scenario', 'invalid_scenario');
}

global $pdo;
if (!$pdo) {
    simFail(500, 'Service unavailable', 'db_unavailable');
}

$connector = new SimulatedScannerConnector();
$context = [
    'practice_id'    => (int)$currentPracticeId,
    'connection_id'  => null,
    'source_account' => 'simulated-dev',
    'is_synthetic'   => true,
    'scenario'       => $scenario,
];

$results = [];
foreach ($connector->fetchScans($context) as $payload) {
    $results[] = ScanIngestion::ingestScan($pdo, $context, $connector, $payload);
}

echo json_encode([
    'success'  => true,
    'scenario' => $scenario,
    'ingested' => $results,
]);
exit;
