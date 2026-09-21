<?php
// Returns case activity events for a given case ID so the UI can show revision history

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/user-display.php';

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

// SECURITY: Verify the case belongs to the current practice and, for
// limited-visibility users, is assigned to them.
requireCaseAccess($caseId, $currentPracticeId);

ensureCaseActivityLogTable();

try {
    $sql = "SELECT case_id, event_type, old_status, new_status, user_id, user_email, created_at, meta_json
            FROM case_activity_log
            WHERE case_id = :case_id
            ORDER BY created_at DESC, id DESC
            LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['case_id' => $caseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve actor display names in one query: "First Last" -> account
    // email -> email recorded on the event. Members provisioned with an
    // email-only users row resolve to their email instead of a blank name.
    $actorIds = array_values(array_unique(array_filter(array_map(function ($r) {
        return isset($r['user_id']) ? (int)$r['user_id'] : null;
    }, $rows))));
    $actorNames = [];
    if ($actorIds) {
        $placeholders = implode(',', array_fill(0, count($actorIds), '?'));
        $userStmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id IN ($placeholders)");
        $userStmt->execute($actorIds);
        while ($u = $userStmt->fetch(PDO::FETCH_ASSOC)) {
            $actorNames[(int)$u['id']] = formatUserDisplayName($u['first_name'] ?? '', $u['last_name'] ?? '', $u['email'] ?? '', '');
        }
    }

    $events = [];
    foreach ($rows as $row) {
        $meta = null;
        if (!empty($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $actorName = '';
        if (isset($row['user_id']) && isset($actorNames[(int)$row['user_id']])) {
            $actorName = $actorNames[(int)$row['user_id']];
        }
        if ($actorName === '') {
            $actorName = (string)($row['user_email'] ?? '');
        }

        $events[] = [
            'case_id'    => $row['case_id'],
            'event_type' => $row['event_type'],
            'old_status' => $row['old_status'],
            'new_status' => $row['new_status'],
            'user_email' => $row['user_email'],
            'user_name'  => $actorName !== '' ? $actorName : null,
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
