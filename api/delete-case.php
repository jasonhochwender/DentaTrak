<?php
/**
 * Delete Case API Endpoint
 * Deletes a case and associated Google Drive files
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set security headers
setApiSecurityHeaders();

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

// Validate CSRF token for state-changing requests
requireCsrfToken();

// Get the current practice ID and check if user is admin
$isAdmin = false;
if ($currentPracticeId) {
    try {
        $stmt = $pdo->prepare("
            SELECT role 
            FROM practice_users 
            WHERE practice_id = :practice_id 
            AND user_id = :user_id
        ");
        $stmt->execute([
            'practice_id' => $currentPracticeId,
            'user_id' => $userId
        ]);
        $role = $stmt->fetchColumn();
        $isAdmin = ($role === 'admin');
    } catch (PDOException $e) {
        // Handle error silently
        error_log("Error checking user role: " . $e->getMessage());
    }
}

// Check user preferences to see if card deletion is allowed
// Since the checkbox is checked by default in the UI, we allow archiving by default
// regardless of the database preference value
try {
    $stmt = $pdo->prepare("
        SELECT allow_card_delete 
        FROM user_preferences 
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $preferenceValue = $stmt->fetchColumn();
    // Allow archiving by default since the UI checkbox is checked by default
    $allowCardDelete = true;
} catch (PDOException $e) {
    $allowCardDelete = true; // Default to true on error
    error_log("Error checking user preferences: " . $e->getMessage());
}

// Only allow archiving if card deletion/archiving is allowed in preferences
// (Admin requirement removed - if user can see the button, they can use it)
if (!$allowCardDelete) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to archive cases']);
    exit;
}

// Get JSON data from the request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

// Check for required fields
if (!isset($data['caseId']) || empty($data['caseId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Case ID is required']);
    exit;
}

$caseId = $data['caseId'];
$driveFolderId = $data['driveFolderId'] ?? '';

try {
    // Optionally clean up relational data without depending on a dental_cases table
    if ($pdo) {
        try {
            // Delete case assignments if the table exists
            $check = $pdo->query("SHOW TABLES LIKE 'case_assignments'");
            if ($check && $check->rowCount() > 0) {
                $stmt = $pdo->prepare("DELETE FROM case_assignments WHERE case_id = :case_id");
                $stmt->execute(['case_id' => $caseId]);
            }
        } catch (PDOException $e) {
            // Log but don't block the delete if this cleanup fails
            error_log("Error deleting case assignments for case {$caseId}: " . $e->getMessage());
        }
    }

    // Archive the case in cache instead of deleting it
    archiveCaseInCache($caseId);

    // Update user's case count
    if ($currentPracticeId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ? AND archived = 0");
        $stmt->execute([$currentPracticeId]);
        $newCaseCount = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("UPDATE users SET case_count = ? WHERE id = ?");
        $stmt->execute([$newCaseCount, $userId]);
    }

    // Archive Google Drive folder to the practice Archive if ID is provided
    $driveArchived = false;
    if (!empty($driveFolderId)) {
        $practiceIdForArchive = $currentPracticeId ? (int)$currentPracticeId : 0;
        $driveArchived = archivePracticeCaseFolder($practiceIdForArchive, $driveFolderId);
    }

    // Log the activity to both the user activity log and the case activity log
    logUserActivity($userId, 'archive_case', "User archived case ID: {$caseId}");

    logCaseActivity(
        $caseId,
        'case_archived',
        null,
        null,
        [
            'drive_folder_id' => $driveFolderId,
            'drive_archived' => $driveArchived,
            'source' => 'delete-case.php',
        ]
    );

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Case archived successfully',
        'driveArchived' => $driveArchived
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error archiving case: ' . $e->getMessage()
    ]);

    error_log("Error archiving case: " . $e->getMessage());
}

/**
 * Function to delete a Google Drive folder
 * 
 * @param string $folderId Google Drive folder ID
 * @return bool True if deletion was successful
 */
function deleteGoogleDriveFolder($folderId) {
    if (empty($folderId)) {
        return false;
    }

    try {
        // Use the existing Google Drive client helpers
        $client = getGoogleClient();

        // Ensure we have a valid access token
        if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
            // getGoogleClient() already attempts refresh using the stored refresh token
            // If we still don't have a usable token, return redirect
            logGDMsg("Cannot delete folder {$folderId}: no valid Google Drive access token");
            echo json_encode([
                'success' => false,
                'redirect' => 'login.php',
                'message' => 'Your session has expired. Please log in again.'
            ]);
            exit;
        }

        $service = new Google_Service_Drive($client);

        // Delete (trash) the folder in Drive. This removes it from listCases() results
        $fileMetadata = new Google_Service_Drive_DriveFile([
            'trashed' => true
        ]);
        $service->files->update($folderId, $fileMetadata);

        return true;
    } catch (Exception $e) {
        // Log the underlying Drive error but do not surface raw details to the UI
        logGDMsg("Error deleting folder {$folderId}: " . $e->getMessage());
        return false;
    }
}
