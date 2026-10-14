<?php
// Open Dental patient launch resolver
//
// Called by js/od-launch.js after the Open Dental toolbar bridge lands the
// user on main.php with a pending patient hint. Resolves the external
// PatNum to this practice's imported DentaTrak case ids. The PatNum is a
// lookup hint only - authentication, 2FA, practice membership, and practice
// isolation are all enforced upstream by requireValidPracticeContext().
//
// No PHI is accepted, returned beyond the case names the user can already
// see, or logged.

require_once __DIR__ . '/session.php';      // Centralized session handling
header('Content-Type: application/json');

// Do not show errors in the browser for this endpoint
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/integrations/OdPatientLaunch.php';

// SECURITY: Require valid practice context before accessing any data.
// Resolution is scoped to $_SESSION['current_practice_id'] - a PatNum from
// another practice's Open Dental simply resolves to nothing.
$currentPracticeId = requireValidPracticeContext();

$pat = isset($_GET['pat']) ? trim((string)$_GET['pat']) : '';
if (!preg_match('/^\d{1,20}$/', $pat)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'invalid_patient']);
    exit;
}

try {
    $result = OdPatientLaunch::resolvePatientCases($pdo, (int)$currentPracticeId, $pat);
    echo json_encode([
        'success'      => true,
        'status'       => $result['status'],
        'case_ids'     => $result['case_ids'],
        'case_count'   => $result['case_count'],
        'patient_name' => $result['patient_name'],
    ]);
} catch (Throwable $e) {
    // Never log the PatNum or any patient data - ids only.
    error_log('[OdPatientLaunch] resolution failed for practice ' . (int)$currentPracticeId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'resolve_failed']);
}
