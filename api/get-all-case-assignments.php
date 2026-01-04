<?php
/**
 * Get All Case Assignments API Endpoint
 * Returns all case assignments for the current user's practice
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';

// Set header to JSON
header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();

try {
    // Check if case_assignments table exists first
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'case_assignments'");
    if ($tableCheck->rowCount() === 0) {
        // Table doesn't exist yet - return empty assignments (not an error)
        echo json_encode([
            'success' => true,
            'assignments' => []
        ]);
        exit;
    }
    
    // SECURITY: Only get case assignments for cases in the current practice
    $stmt = $pdo->prepare("
        SELECT 
            ca.case_id,
            u.email
        FROM case_assignments ca
        JOIN users u ON ca.user_id = u.id
        JOIN cases_cache cc ON ca.case_id = cc.case_id
        WHERE cc.practice_id = :practice_id
    ");
    $stmt->execute(['practice_id' => $currentPracticeId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'assignments' => $assignments
    ]);
    
} catch (PDOException $e) {
    // Check if it's a "table doesn't exist" error - return empty array instead of 500
    if (strpos($e->getMessage(), "doesn't exist") !== false || 
        strpos($e->getMessage(), 'case_assignments') !== false) {
        echo json_encode([
            'success' => true,
            'assignments' => []
        ]);
        exit;
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching assignments'
    ]);
    
    if (function_exists('userLog')) {
        userLog("Error fetching case assignments: " . $e->getMessage(), true);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching assignments'
    ]);
    
    if (function_exists('userLog')) {
        userLog("Error fetching case assignments: " . $e->getMessage(), true);
    }
}
