<?php
/**
 * Get User Practices API Endpoint
 *
 * Returns all practices the current authenticated, active user belongs to,
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
        'error' => t('auth.errors.not_authenticated')
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];
$currentPracticeId = $_SESSION['current_practice_id'] ?? null;

try {
    // Get all active practices the user belongs to. practice_users does not
    // have its own is_active column; an active membership is an existing row
    // where both the user and the practice are active.
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.practice_id as uuid,
            p.practice_name,
            p.logo_path,
            p.baa_accepted,
            p.organization_type,
            pu.role,
            pu.is_owner,
            IFNULL(pu.limited_visibility, 0) AS limited_visibility,
            IFNULL(pu.can_view_analytics, 1) AS can_view_analytics,
            IFNULL(pu.can_edit_cases, 1) AS can_edit_cases,
            IFNULL(pu.is_lab, 0) AS is_lab
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        JOIN users u ON u.id = pu.user_id
        WHERE pu.user_id = :user_id
          AND u.is_active = 1
          AND (p.is_active = 1 OR p.is_active IS NULL)
        ORDER BY p.practice_name ASC
    ");
    $stmt->execute(['user_id' => $userId]);
    $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark the current practice
    foreach ($practices as &$practice) {
        $practice['is_current'] = ($currentPracticeId && (int) $practice['id'] === (int) $currentPracticeId);
        $practice['id'] = (int) $practice['id'];
        $practice['is_owner'] = (bool) $practice['is_owner'];
        $practice['baa_accepted'] = (bool) $practice['baa_accepted'];
        $practice['limited_visibility'] = (bool) $practice['limited_visibility'];
        $practice['can_view_analytics'] = (bool) $practice['can_view_analytics'];
        $practice['can_edit_cases'] = (bool) $practice['can_edit_cases'];
        $practice['is_lab'] = (bool) $practice['is_lab'];
    }
    unset($practice);

    echo json_encode([
        'success' => true,
        'practices' => $practices,
        'current_practice_id' => $currentPracticeId ? (int) $currentPracticeId : null,
        'has_multiple' => count($practices) > 1
    ]);

} catch (PDOException $e) {
    error_log("Error fetching user practices: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => t('auth.errors.failed_fetch_practices')
    ]);
}
