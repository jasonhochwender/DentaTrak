<?php
/**
 * Get Organization Practice Options API
 *
 * Consolidated "All practices" view support: returns the practice-scoped
 * option sets (assignable users, assignment labels, workflow status
 * options) needed to open/edit a case that lives in a DIFFERENT authorized
 * practice than the session's anchor practice.
 *
 * The requested practice is validated against the live authorized
 * organization scope (getOrgCaseViewPractices) - membership, organization
 * authority, and the Scale entitlement are all re-derived per request.
 * Visibility alone does not grant edit rights: `canEdit` reports the
 * caller's per-practice permission so the client can render read-only.
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/organization-access.php';
require_once __DIR__ . '/workflow-stages.php';

// SECURITY: Require valid practice context (the session anchor practice).
$currentPracticeId = requireValidPracticeContext();
$userId = (int)($_SESSION['db_user_id'] ?? 0);

// Only meaningful while the consolidated view is active AND entitled.
if (!orgCaseViewEntitled($pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'The consolidated multi-practice view is not available.']);
    exit;
}

$targetPracticeId = filter_var($_GET['practice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($targetPracticeId === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'practice_id is required']);
    exit;
}
$targetPracticeId = (int)$targetPracticeId;

// Validate against the authorized organization scope (real practices only;
// no synthetic scope id can ever reach this point).
$authorized = null;
foreach (getOrgCaseViewPractices($pdo) as $ap) {
    if ((int)$ap['id'] === $targetPracticeId) {
        $authorized = $ap;
        break;
    }
}
if ($authorized === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. You do not have permission to access this practice.']);
    exit;
}

try {
    // Assignable users: active members of the target practice, mirroring
    // the adminUsers + gmailUsers lists the single-practice modal uses.
    $adminUsers = [];
    $gmailUsers = [];
    $stmt = $pdo->prepare("
        SELECT u.email, pu.role, pu.is_owner
        FROM users u
        JOIN practice_users pu ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id
          AND u.is_active = 1
        ORDER BY pu.created_at ASC
    ");
    $stmt->execute(['practice_id' => $targetPracticeId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = (string)$row['email'];
        if ($email === '') continue;
        if ($row['role'] === 'admin' || !empty($row['is_owner'])) {
            $adminUsers[] = $email;
        } else {
            $gmailUsers[] = $email;
        }
    }

    // Assignment labels for the target practice.
    $assignmentLabels = [];
    try {
        $stmt = $pdo->prepare("
            SELECT label FROM practice_assignment_labels
            WHERE practice_id = :practice_id
            ORDER BY sort_order ASC, label ASC
        ");
        $stmt->execute(['practice_id' => $targetPracticeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $label) {
            $label = trim((string)$label);
            if ($label !== '' && !in_array($label, $assignmentLabels, true)) {
                $assignmentLabels[] = $label;
            }
        }
    } catch (PDOException $e) {
        // Table may not exist - no labels available
    }

    // Active workflow columns (id -> resolved label, board order) for the
    // status select - resolved against the TARGET practice, not the anchor.
    $statusOptions = [];
    $resolvedLabels = getResolvedWorkflowStageLabelsForPractice($targetPracticeId);
    foreach (getWorkflowColumnsForPractice($targetPracticeId) as $column) {
        if (!empty($column['archived'])) continue;
        $id = (string)$column['id'];
        $statusOptions[] = [
            'id' => $id,
            'label' => $resolvedLabels[$id] ?? $id,
        ];
    }

    echo json_encode([
        'success' => true,
        'practiceId' => $targetPracticeId,
        'practiceName' => (string)($authorized['practice_name'] ?? ''),
        'adminUsers' => array_values($adminUsers),
        'gmailUsers' => array_values($gmailUsers),
        'assignmentLabels' => array_values($assignmentLabels),
        'statusOptions' => $statusOptions,
        'canEdit' => !empty($authorized['can_edit_cases']),
    ]);
} catch (Throwable $e) {
    error_log('[get-org-practice-options] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching practice options']);
}
