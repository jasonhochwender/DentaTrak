<?php
/**
 * Update Case Assignment API Endpoint
 * Updates the assigned user for a case
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notification-service.php';

// Set header to JSON
header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();
$currentUserId = $_SESSION['db_user_id'];

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['caseId']) || empty($data['caseId'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => t('api.assignments.case_id_required')
    ]);
    exit;
}

$caseId = $data['caseId'];
$assignedTo = isset($data['assignedTo']) ? $data['assignedTo'] : '';

// SECURITY: Verify this case belongs to the current practice and, for
// Assigned Only users, is currently assigned to them, before allowing the
// assignment to be changed.
requireCaseAccess($caseId, $currentPracticeId);

try {
    // Variable to track if Google Drive update was successful
    $driveUpdateSuccess = false;
    $caseFolderId = null;
    
    // Try to update Google Drive case.json file if possible
    try {
        require_once __DIR__ . '/google-drive.php';
        
        $client = getGoogleClient();
        
        // Check for valid access token
        if ($client->getAccessToken() && !$client->isAccessTokenExpired()) {
            $service = new Google_Service_Drive($client);
            
            // Find the case folder ID
            // We need to look up the case by ID to get its folder ID
            // This is a simplified version - in a real system, you might store this mapping in a database
            
            // First, get all cases
            $casesResponse = [];
            
            // Skip Google Drive update if dentalCasesFolderId is not set
            if (isset($appConfig['dentalCasesFolderId']) && !empty($appConfig['dentalCasesFolderId'])) {
                $dentalCasesFolder = $appConfig['dentalCasesFolderId'];
                
                // Try to get folders - be extra cautious with error handling
                try {
                    $folders = $service->files->listFiles([
                        'q' => "'" . $dentalCasesFolder . "' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false",
                        'fields' => 'files(id, name)'
                    ]);
                } catch (Exception $e) {
                    userLog("Error listing case folders: " . $e->getMessage(), true);
                    $folders = null;
                }
                
                // Iterate through case folders to find the target case
                if ($folders !== null) {
                    foreach ($folders->getFiles() as $folder) {
                    // For each folder, check if it has a case.json file
                    $caseFiles = $service->files->listFiles([
                        'q' => "'" . $folder->getId() . "' in parents and name='case.json' and trashed=false",
                        'fields' => 'files(id)'
                    ]);
                    
                    if (count($caseFiles->getFiles()) > 0) {
                        $caseFileId = $caseFiles->getFiles()[0]->getId();
                        
                        // Get the case.json content
                        $content = $service->files->get($caseFileId, ['alt' => 'media']);
                        $caseData = json_decode($content->getBody()->getContents(), true);
                        
                        // Check if this is the case we're looking for
                        if ($caseData && isset($caseData['id']) && $caseData['id'] === $caseId) {
                            $caseFolderId = $folder->getId();
                            
                            // Update the assignedTo property
                            $caseData['assignedTo'] = $assignedTo;
                            $caseData['lastUpdateDate'] = date('c'); // Update the timestamp
                            
                            // Update case.json file in Drive
                            $updatedFile = new Google_Service_Drive_DriveFile();
                            $service->files->update($caseFileId, $updatedFile, [
                                'data' => json_encode($caseData, JSON_PRETTY_PRINT),
                                'mimeType' => 'application/json',
                                'uploadType' => 'multipart'
                            ]);
                            
                            $driveUpdateSuccess = true;
                            break;
                        }
                    }
                    } // Close the foreach loop
                }
            }
        }
    } catch (Exception $e) {
        // Log the Google Drive error but continue with the database update
        userLog("Google Drive update error: " . $e->getMessage(), true);
    }
    
    // Get previous assignee for real-time notification tracking
    $previousAssignee = null;
    try {
        $stmt = $pdo->prepare("SELECT assigned_to FROM cases_cache WHERE case_id = :case_id LIMIT 1");
        $stmt->execute(['case_id' => $caseId]);
        $prevRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prevRow && !empty($prevRow['assigned_to'])) {
            $previousAssignee = $prevRow['assigned_to'];
        }
    } catch (Exception $e) {
        // Ignore errors fetching previous assignee
    }
    
    // Now update or create the assignment in the database
    //
    // SECURITY / DATA INTEGRITY: this must NEVER create a phantom user
    // record. assignedTo can be one of three things:
    //   1. An Assignment Label (e.g. "Lab", "Front Desk") - a shared
    //      responsibility that does not correspond to a DentaTrak user
    //      account. Labels are stored ONLY in cases_cache.assigned_to;
    //      they never touch the users or case_assignments tables.
    //   2. A real, existing user's email - the normal case. The relational
    //      case_assignments row is created/updated as before.
    //   3. Anything else (an email with no matching account, or an
    //      arbitrary string that isn't a recognized label) - previously
    //      this silently created a new "users" row for whatever string
    //      was submitted. That phantom-user behavior has been removed
    //      entirely: the raw string is still stored in cases_cache (same
    //      as case creation already does), but no users/case_assignments
    //      row is created or modified.
    $assigneeUserIdForAudit = null;
    $isAssignmentLabel = false;

    if (!empty($assignedTo)) {
        // Check whether this value matches an Assignment Label for the
        // current practice (case-insensitive), before ever considering it
        // a user.
        $stmt = $pdo->prepare("
            SELECT 1 FROM practice_assignment_labels
            WHERE practice_id = :practice_id AND LOWER(label) = LOWER(:label)
            LIMIT 1
        ");
        $stmt->execute([
            'practice_id' => $currentPracticeId,
            'label' => $assignedTo
        ]);
        $isAssignmentLabel = (bool)$stmt->fetchColumn();
    }

    if ($isAssignmentLabel) {
        // Label assignment: not a person, so remove any existing
        // relational assignment row (a label can't be "assigned to" in
        // the case_assignments/user sense) and never touch users.
        $stmt = $pdo->prepare("DELETE FROM case_assignments WHERE case_id = :case_id");
        $stmt->execute(['case_id' => $caseId]);
    } elseif (!empty($assignedTo)) {
        // Try to resolve to an existing real user by email.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $assignedTo]);
        $assigneeUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($assigneeUser) {
            $assigneeId = $assigneeUser['id'];
            $assigneeUserIdForAudit = $assigneeId;

            // Check if assignment already exists
            $stmt = $pdo->prepare("SELECT id FROM case_assignments WHERE case_id = :case_id LIMIT 1");
            $stmt->execute(['case_id' => $caseId]);
            $existingAssignment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingAssignment) {
                // Update existing assignment
                $stmt = $pdo->prepare("
                    UPDATE case_assignments 
                    SET user_id = :user_id, assigned_by = :assigned_by, updated_at = NOW() 
                    WHERE id = :id
                ");
                $stmt->execute([
                    'user_id' => $assigneeId,
                    'assigned_by' => $currentUserId,
                    'id' => $existingAssignment['id']
                ]);
            } else {
                // Create new assignment
                $stmt = $pdo->prepare("
                    INSERT INTO case_assignments (case_id, user_id, assigned_by) 
                    VALUES (:case_id, :user_id, :assigned_by)
                ");
                $stmt->execute([
                    'case_id' => $caseId,
                    'user_id' => $assigneeId,
                    'assigned_by' => $currentUserId
                ]);
            }
        } else {
            // No matching user and not a recognized label: store the raw
            // value in cases_cache only (below) - do NOT create a user or
            // a case_assignments row. Also clear any stale relational
            // assignment left over from a previous real-user assignment.
            $stmt = $pdo->prepare("DELETE FROM case_assignments WHERE case_id = :case_id");
            $stmt->execute(['case_id' => $caseId]);
            userLog("Assignment set to unrecognized value '{$assignedTo}' for case {$caseId} - stored as plain text, no user created", false);
        }
    } else {
        // If assignedTo is empty, remove any existing assignment
        $stmt = $pdo->prepare("DELETE FROM case_assignments WHERE case_id = :case_id");
        $stmt->execute(['case_id' => $caseId]);
    }
    
    // Log assignment change as a case activity event
    $eventType = ($assignedTo !== '') ? 'assignment_set' : 'assignment_cleared';
    logCaseActivity(
        $caseId,
        $eventType,
        null,
        null,
        [
            'assignee_user_id' => $assigneeUserIdForAudit,
            'assigned_to' => $assignedTo,
            'old_assigned_to' => $previousAssignee,
            'source' => 'update-case-assignment.php',
        ]
    );

    // Log the activity to the existing user activity log if available
    if (function_exists('logUserActivity')) {
        logUserActivity($currentUserId, 'update_assignment', "User updated case assignment for case {$caseId}");
    } else {
        userLog("User {$currentUserId} updated case assignment for case {$caseId}", false);
    }

    // Update local cache with new assignment
    updateCaseAssignedToInCache($caseId, $assignedTo);

    // Lab Insights foundation: record any lab-assignment-period transition.
    // No-op for practices/entities with no Lab-designated user or label.
    recordLabAssignmentChange($caseId, $currentPracticeId, $previousAssignee, $assignedTo);

    // Record assignment change for real-time notifications to other users
    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'assignment', null, $previousAssignee ?? null);
    }

    // Emit structured in-app notification for assignment change (Phase 2).
    if ($assignedTo !== '' && $assignedTo !== $previousAssignee && $currentPracticeId) {
        $categories = ['assignment_changed'];
        $metadata = [
            'assignment_set' => true,
            'from_previous' => $previousAssignee !== '' && $previousAssignee !== null,
        ];
        $eventType = getPrimaryNotificationType($categories);
        emitCaseNotificationEvent($currentPracticeId, $caseId, $currentUserId, $eventType, $categories, $metadata);
    }

    // Return success
    echo json_encode([
        'success' => true,
        'message' => t('api.assignments.updated_success'),
        'caseId' => $caseId,
        'assignedTo' => $assignedTo,
        'driveUpdateSuccess' => $driveUpdateSuccess
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => t('api.assignments.update_error', ['message' => $e->getMessage()])
    ]);
    
    userLog("Error updating case assignment: " . $e->getMessage(), true);
}
