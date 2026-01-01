<?php
// Returns case activity events for a given case ID so the UI can show revision history

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/case-activity-log.php';

header('Content-Type: application/json');

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();

$caseId = isset($_GET['caseId']) ? trim($_GET['caseId']) : '';

if ($caseId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'caseId is required',
    ]);
    exit;
}

global $pdo;

if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection not available',
    ]);
    exit;
}

// SECURITY: Verify the case belongs to the current practice
$stmt = $pdo->prepare("SELECT practice_id FROM cases_cache WHERE case_id = :case_id");
$stmt->execute(['case_id' => $caseId]);
$casePracticeId = $stmt->fetchColumn();

if (!$casePracticeId || (int)$casePracticeId !== (int)$currentPracticeId) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Case not found or access denied',
    ]);
    exit;
}

ensureCaseActivityLogTable();

try {
    $sql = "SELECT case_id, event_type, old_status, new_status, user_email, created_at, meta_json
            FROM case_activity_log
            WHERE case_id = :case_id
            ORDER BY created_at DESC, id DESC
            LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['case_id' => $caseId]);

    $events = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $meta = null;
        if (!empty($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $events[] = [
            'case_id'    => $row['case_id'],
            'event_type' => $row['event_type'],
            'old_status' => $row['old_status'],
            'new_status' => $row['new_status'],
            'user_email' => $row['user_email'],
            'created_at' => $row['created_at'],
            'meta'       => $meta,
        ];
    }

    echo json_encode([
        'success' => true,
        'events'  => $events,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load case activity',
    ]);
}
