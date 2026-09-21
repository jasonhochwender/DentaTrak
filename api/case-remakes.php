<?php
/**
 * Case Remakes API Endpoint
 *
 * GET  ?caseId=...            -> list a case's remake records
 * POST {action:'record',   caseId, reasonCode, attribution, notes?}
 * POST {action:'complete', remakeId, caseId}
 *
 * A remake is always an explicit user-recorded event - never inferred
 * from workflow regressions or status moves.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/remakes.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();
$currentUserId = $_SESSION['db_user_id'];

// Validate CSRF token for state-changing requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $caseId = $_GET['caseId'] ?? '';
    if (empty($caseId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('api.cases.case_id_required')]);
        exit;
    }

    // SECURITY: same case-level access rule as every other case endpoint.
    requireCaseAccess($caseId, $currentPracticeId);

    echo json_encode([
        'success' => true,
        'remakes' => getCaseRemakes($caseId, $currentPracticeId),
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'record';
$caseId = $input['caseId'] ?? '';

if (empty($caseId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('api.cases.case_id_required')]);
    exit;
}

// SECURITY: Verify this case belongs to the current practice and, for
// Assigned Only users, is assigned to them.
$caseInfo = requireCaseAccess($caseId, $currentPracticeId);

// Remakes are recorded against live cases only - archived cases are
// read-only history (restore first if the case is active again).
if ((int)($caseInfo['archived'] ?? 0) === 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('api.remakes.case_archived')]);
    exit;
}

if ($action === 'record') {
    $reasonCode = $input['reasonCode'] ?? '';
    $attribution = $input['attribution'] ?? '';
    $notes = $input['notes'] ?? '';

    if (!isValidRemakeReason($reasonCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('api.remakes.invalid_reason'), 'field' => 'reasonCode']);
        exit;
    }
    if (!isValidRemakeAttribution($attribution)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('api.remakes.invalid_attribution'), 'field' => 'attribution']);
        exit;
    }

    $remake = createCaseRemake($caseId, $currentPracticeId, $reasonCode, $attribution, $notes, $currentUserId);
    if (!$remake) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('api.remakes.record_failed')]);
        exit;
    }

    // Activity timeline: codes only in metadata (no PHI), actor from session.
    logCaseActivity(
        $caseId,
        'remake_initiated',
        null,
        $caseInfo['status'] ?? null,
        [
            'remake_id' => $remake['id'],
            'remake_number' => $remake['remake_number'],
            'remake_reason' => $reasonCode,
            'remake_attribution' => $attribution,
            'lab_period_id' => $remake['lab_period_id'],
            'source' => 'case-remakes.php',
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => t('api.remakes.recorded'),
        'remake' => $remake,
    ]);
    exit;
}

if ($action === 'complete') {
    $remakeId = (int)($input['remakeId'] ?? 0);
    if ($remakeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('api.remakes.invalid_remake')]);
        exit;
    }

    $result = completeCaseRemake($remakeId, $caseId, $currentPracticeId);
    if ($result === 'not_found') {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('api.remakes.not_found')]);
        exit;
    }

    // Idempotent: the activity entry is written only on the real
    // transition, so a repeated submit cannot double-log.
    if ($result === 'completed') {
        $remakeStmt = $pdo->prepare("SELECT remake_number FROM case_remake_events WHERE id = :id LIMIT 1");
        $remakeStmt->execute(['id' => $remakeId]);
        $remakeNumber = (int)$remakeStmt->fetchColumn();

        logCaseActivity(
            $caseId,
            'remake_completed',
            null,
            $caseInfo['status'] ?? null,
            [
                'remake_id' => $remakeId,
                'remake_number' => $remakeNumber,
                'source' => 'case-remakes.php',
            ]
        );
    }

    echo json_encode([
        'success' => true,
        'message' => t('api.remakes.completed'),
        'alreadyCompleted' => ($result === 'already_completed'),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
