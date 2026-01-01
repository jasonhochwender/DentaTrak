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
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching assignments: ' . $e->getMessage()
    ]);
    
    if (function_exists('userLog')) {
        userLog("Error fetching case assignments: " . $e->getMessage(), true);
    }
}
