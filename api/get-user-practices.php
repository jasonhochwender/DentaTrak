<?php
/**
 * Get User Practices API Endpoint
 * 
 * Returns all practices the current user belongs to,
 * along with their role and permissions in each practice.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Not authenticated'
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];
$currentPracticeId = $_SESSION['current_practice_id'] ?? null;

try {
    // Get all practices the user belongs to
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.practice_id as uuid,
            p.practice_name,
            p.logo_path,
            p.baa_accepted,
            pu.role,
            pu.is_owner,
            pu.limited_visibility,
            pu.can_view_analytics,
            pu.can_edit_cases
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE pu.user_id = :user_id
        ORDER BY p.practice_name ASC
    ");
    $stmt->execute(['user_id' => $userId]);
    $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark the current practice
    foreach ($practices as &$practice) {
        $practice['is_current'] = ($currentPracticeId && (int)$practice['id'] === (int)$currentPracticeId);
        $practice['id'] = (int)$practice['id'];
        $practice['is_owner'] = (bool)$practice['is_owner'];
        $practice['baa_accepted'] = (bool)$practice['baa_accepted'];
        $practice['limited_visibility'] = (bool)$practice['limited_visibility'];
        $practice['can_view_analytics'] = (bool)$practice['can_view_analytics'];
        $practice['can_edit_cases'] = (bool)$practice['can_edit_cases'];
    }
    unset($practice);
    
    echo json_encode([
        'success' => true,
        'practices' => $practices,
        'current_practice_id' => $currentPracticeId ? (int)$currentPracticeId : null,
        'has_multiple' => count($practices) > 1
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching user practices: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch practices'
    ]);
}
