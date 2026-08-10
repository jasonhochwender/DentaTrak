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
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/gcs-attachments.php';
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

// Validate CSRF token (standard app-wide CSRF pattern)
requireCsrfToken();

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
    
    // Get all cases for the current user's practice ONLY. This is a
    // deliberate SELECT before DELETE (rather than a single DELETE
    // statement) so each case's Google Drive folder can also be trashed.
    // SECURITY: There is intentionally no fallback to legacy/orphaned
    // practice_id IS NULL rows here - if the current practice has zero
    // cases, zero cases are deleted. Cleaning up any legacy orphaned rows
    // is a migration concern, not something this everyday Dev Tool should
    // ever touch.
    $stmt = $pdo->prepare("SELECT case_id, drive_folder_id, attachments_json FROM cases_cache WHERE practice_id = ?");
    $stmt->execute([$currentPracticeId]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $deletedCount = 0;
    $errors = [];
    
    foreach ($cases as $case) {
        try {
            // Delete Google Drive folder (move to trash)
            if (!empty($case['drive_folder_id'])) {
                trashDriveFolder($case['drive_folder_id']);
            }

            // SECURITY/STORAGE: This is a PERMANENT delete (unlike archiving
            // via delete-case.php), so physically remove any GCS-backed
            // attachment objects to reclaim storage. Archived cases are
            // never processed here - this endpoint deletes the DB row itself.
            if (!empty($case['attachments_json'])) {
                $attachments = json_decode($case['attachments_json'], true);
                if (is_array($attachments)) {
                    deleteGcsAttachments($attachments);
                }
            }
            
            // Delete case from cases_cache table - strictly scoped to the
            // current practice. $case['case_id'] already only ever came
            // from the practice-scoped SELECT above, and this WHERE clause
            // re-confirms that scoping rather than trusting the prior query.
            $deleteStmt = $pdo->prepare("DELETE FROM cases_cache WHERE case_id = ? AND practice_id = ?");
            $result = $deleteStmt->execute([$case['case_id'], $currentPracticeId]);
            
            if ($result) {
                $deletedCount++;
            } else {
                $errors[] = 'Failed to delete case ID: ' . $case['case_id'];
            }
            
        } catch (Exception $e) {
            $errors[] = 'Error deleting case ID ' . $case['case_id'] . ': ' . $e->getMessage();
        }
    }
    
    // Deleting zero cases (because the current practice already has none)
    // is a successful outcome, not a failure - only report failure if a
    // case that existed in this practice could not actually be deleted.
    if (empty($errors)) {
        echo json_encode([
            'success' => true,
            'message' => $deletedCount > 0
                ? "Successfully deleted {$deletedCount} cases"
                : 'No cases to delete for this practice',
            'deleted_count' => $deletedCount,
            'errors' => $errors
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Some cases could not be deleted',
            'deleted_count' => $deletedCount,
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
