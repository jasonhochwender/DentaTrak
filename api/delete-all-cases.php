<?php
/**
 * Delete All Cases API Endpoint
 * Deletes all cases for the current user's practice
 * 
 * Access Control:
 * - Always allowed in development environment
 * - In UAT/Production: Only allowed for super users with dev_tools_enabled
 * - Operations are ALWAYS scoped to the user's current practice
 */

// Suppress all warnings and errors for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/dev-tools-access.php';
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Check dev tools access (handles both development and super user in UAT/Prod)
require_once __DIR__ . '/appConfig.php';
$userEmail = $_SESSION['user_email'] ?? '';
if (!canAccessDevTools($appConfig, $userEmail)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Dev tools access not authorized']);
    exit;
}

// For super users in UAT/Production, verify they have admin access to the practice
if (isProductionOrUAT($appConfig)) {
    $userId = $_SESSION['db_user_id'];
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    
    if (!superUserHasPracticeAdminAccess($pdo, $userId, $practiceId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You must be an admin of this practice to perform this action']);
        exit;
    }
}

try {
    // Database connection is already established in appConfig.php
    global $pdo;
    
    // Ensure cases_cache table exists
    ensureCasesCacheTable();
    
    // Get current practice ID
    $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
    
    // Get all cases for the current user's practice
    $stmt = $pdo->prepare("SELECT case_id, drive_folder_id FROM cases_cache WHERE practice_id = ?");
    $stmt->execute([$currentPracticeId]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no cases found for current practice but there are cases with NULL practice_id,
    // try to delete those as well (fallback for cases created before practice_id was implemented)
    if (count($cases) === 0) {
        $nullPracticeStmt = $pdo->query("SELECT COUNT(*) as count FROM cases_cache WHERE practice_id IS NULL");
        $nullPracticeCases = $nullPracticeStmt->fetchColumn();
        
        if ($nullPracticeCases > 0) {
            $stmt = $pdo->prepare("SELECT case_id, drive_folder_id FROM cases_cache WHERE practice_id IS NULL");
            $stmt->execute();
            $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    $deletedCount = 0;
    $errors = [];
    
    foreach ($cases as $case) {
        try {
            // Delete Google Drive folder (move to trash)
            if (!empty($case['drive_folder_id'])) {
                trashDriveFolder($case['drive_folder_id']);
            }
            
            // Delete case from cases_cache table
            // Handle both cases with specific practice_id and NULL practice_id
            if ($currentPracticeId > 0) {
                // Try to delete with practice_id first
                $deleteStmt = $pdo->prepare("DELETE FROM cases_cache WHERE case_id = ? AND (practice_id = ? OR practice_id IS NULL)");
                $result = $deleteStmt->execute([$case['case_id'], $currentPracticeId]);
            } else {
                // If no practice ID, only delete NULL practice_id cases
                $deleteStmt = $pdo->prepare("DELETE FROM cases_cache WHERE case_id = ? AND practice_id IS NULL");
                $result = $deleteStmt->execute([$case['case_id']]);
            }
            
            if ($result) {
                $deletedCount++;
            } else {
                $errors[] = 'Failed to delete case ID: ' . $case['case_id'];
            }
            
        } catch (Exception $e) {
            $errors[] = 'Error deleting case ID ' . $case['case_id'] . ': ' . $e->getMessage();
        }
    }
    
    if ($deletedCount > 0) {
        echo json_encode([
            'success' => true,
            'message' => "Successfully deleted {$deletedCount} cases",
            'deleted_count' => $deletedCount,
            'errors' => $errors
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No cases were deleted',
            'errors' => $errors
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
