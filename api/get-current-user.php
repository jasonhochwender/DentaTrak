<?php
/**
 * Get Current User API endpoint
 * Returns the current logged-in user's information
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'User not authenticated'
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];

try {
    // Get user information with practice details
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.profile_picture, u.role, u.first_name, u.last_name,
               p.id as practice_id, p.practice_name, p.practice_id as practice_uuid,
               pu.role as practice_role, pu.is_owner, 
               IFNULL(pu.limited_visibility, 0) as limited_visibility,
               IFNULL(pu.can_view_analytics, 1) as can_view_analytics,
               IFNULL(pu.can_edit_cases, 1) as can_edit_cases
        FROM users u
        LEFT JOIN practice_users pu ON u.id = pu.user_id
        LEFT JOIN practices p ON pu.practice_id = p.id
        WHERE u.id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Convert permissions to boolean
    if ($user) {
        $user['limited_visibility'] = (bool)($user['limited_visibility'] ?? false);
        $user['can_view_analytics'] = (bool)($user['can_view_analytics'] ?? true);
        $user['can_edit_cases'] = (bool)($user['can_edit_cases'] ?? true);
    }
    
    // Get all practices this user belongs to
    $practicesStmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.practice_id as uuid, pu.role, pu.is_owner, 
               IFNULL(pu.limited_visibility, 0) as limited_visibility,
               IFNULL(pu.can_view_analytics, 1) as can_view_analytics,
               IFNULL(pu.can_edit_cases, 1) as can_edit_cases
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE pu.user_id = :user_id
    ");
    $practicesStmt->execute(['user_id' => $userId]);
    $practices = $practicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert permissions to boolean for each practice
    foreach ($practices as &$practice) {
        $practice['limited_visibility'] = (bool)($practice['limited_visibility'] ?? false);
        $practice['can_view_analytics'] = (bool)($practice['can_view_analytics'] ?? true);
        $practice['can_edit_cases'] = (bool)($practice['can_edit_cases'] ?? true);
    }
    
    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'user' => $user,
        'practices' => $practices,
        'needs_practice_setup' => empty($practices),
        'has_multiple_practices' => count($practices) > 1
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to retrieve user information'
    ]);
    
    userLog("Error getting user information: " . $e->getMessage(), true);
}
