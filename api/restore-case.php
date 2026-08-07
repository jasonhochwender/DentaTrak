<?php
/**
 * Restore Archived Case API Endpoint
 * Restores an archived case by setting archived = 0
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$caseId = $input['caseId'] ?? '';

if (empty($caseId)) {
    echo json_encode(['success' => false, 'message' => 'Case ID is required']);
    exit;
}

try {
    // SECURITY: Verify this case belongs to the current practice and, for
    // Assigned Only users, is assigned to them.
    $caseInfo = requireCaseAccess($caseId, $currentPracticeId);

    // Business rule: can only restore a case that is actually archived.
    if ((int)($caseInfo['archived'] ?? 0) !== 1) {
        echo json_encode(['success' => false, 'message' => 'Archived case not found']);
        exit;
    }
    
    // Restore the case (unarchive it)
    $updateSql = "
        UPDATE cases_cache 
        SET archived = 0, archived_date = NULL 
        WHERE case_id = :case_id
    ";
    
    $updateStmt = $pdo->prepare($updateSql);
    $result = $updateStmt->execute(['case_id' => $caseId]);
    
    if ($result) {
        // Update user's case count
        if ($currentPracticeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ? AND archived = 0");
            $stmt->execute([$currentPracticeId]);
            $newCaseCount = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("UPDATE users SET case_count = ? WHERE id = ?");
            $stmt->execute([$newCaseCount, $userId]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Case restored successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to restore case'
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to restore case'
    ]);
    
    error_log('Error restoring case: ' . $e->getMessage());
}
?>
